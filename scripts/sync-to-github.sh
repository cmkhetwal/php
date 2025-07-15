#!/bin/bash

# Script to sync the codebase to GitHub repository
# Usage: ./scripts/sync-to-github.sh

set -e

# Configuration
REPO_URL="git@github.com:cmkhetwal/php.git"
BRANCH="main"

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Starting GitHub sync process...${NC}"

# Check if git is installed
if ! command -v git &> /dev/null; then
    echo -e "${RED}Error: git is not installed. Please install git first.${NC}"
    exit 1
fi

# Check if we're in the project root
if [ ! -f "composer.json" ] || [ ! -d "src" ]; then
    echo -e "${RED}Error: Please run this script from the project root directory.${NC}"
    exit 1
fi

# Initialize git if not already initialized
if [ ! -d ".git" ]; then
    echo -e "${YELLOW}Initializing git repository...${NC}"
    git init
    git remote add origin $REPO_URL
    echo -e "${GREEN}Git repository initialized.${NC}"
else
    # Check if remote exists
    if ! git remote | grep -q "origin"; then
        echo -e "${YELLOW}Adding remote origin...${NC}"
        git remote add origin $REPO_URL
    else
        # Update remote URL if needed
        git remote set-url origin $REPO_URL
    fi
    echo -e "${GREEN}Git remote configured.${NC}"
fi

# Create .gitignore if it doesn't exist
if [ ! -f ".gitignore" ]; then
    echo -e "${YELLOW}Creating .gitignore file...${NC}"
    cat > .gitignore << EOL
# Environment variables
.env
.env.*
!.env.example

# Composer dependencies
/vendor/
composer.phar

# Node.js dependencies
/node_modules/
npm-debug.log
yarn-error.log

# IDE and editor files
/.idea/
/.vscode/
*.sublime-project
*.sublime-workspace
*.swp
*.swo

# OS generated files
.DS_Store
Thumbs.db

# Application runtime files
/storage/logs/*
!/storage/logs/.gitkeep
/storage/cache/*
!/storage/cache/.gitkeep
/storage/uploads/*
!/storage/uploads/.gitkeep

# Test coverage
/coverage/
.phpunit.result.cache

# Build artifacts
/build/
/dist/

# Docker volumes
/docker/volumes/
EOL
    echo -e "${GREEN}.gitignore file created.${NC}"
fi

# Create necessary directories for git to track
mkdir -p storage/logs storage/cache storage/uploads
touch storage/logs/.gitkeep storage/cache/.gitkeep storage/uploads/.gitkeep

# Add all files to git
echo -e "${YELLOW}Adding files to git...${NC}"
git add .

# Commit changes
echo -e "${YELLOW}Committing changes...${NC}"
git commit -m "Initial commit: NextGen PHP Application"

# Create branch if it doesn't exist
if ! git show-ref --verify --quiet refs/heads/$BRANCH; then
    echo -e "${YELLOW}Creating branch $BRANCH...${NC}"
    git checkout -b $BRANCH
else
    echo -e "${YELLOW}Switching to branch $BRANCH...${NC}"
    git checkout $BRANCH
fi

# Push to GitHub
echo -e "${YELLOW}Pushing to GitHub...${NC}"
git push -u origin $BRANCH

echo -e "${GREEN}Successfully synced code to GitHub repository!${NC}"
echo -e "${GREEN}Repository URL: $REPO_URL${NC}"
echo -e "${GREEN}Branch: $BRANCH${NC}"
